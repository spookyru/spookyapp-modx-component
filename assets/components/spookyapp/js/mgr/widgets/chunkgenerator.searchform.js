/**
 * SpookyApp — Chunk Generator Search Form
 *
 * ═══════════════════════════════════════════════════════════════
 * Форма поиска контента.
 * Отправляет запрос к процессору chunkgenerator/search
 * и передаёт результаты через событие 'search-success'.
 *
 * Конфигурация:
 *   - contentType (string): Тип контента для поиска
 *   - showYearField (bool):  Показывать поле Year
 *   - subtypes (array|null): Подтипы для Sports табы
 *
 * Events:
 *   - search-success(results, type, query)
 *   - search-failure(message)
 * ═══════════════════════════════════════════════════════════════
 *
 * @class   SpookyApp.form.ChunkGeneratorSearch
 * @extends Ext.form.FormPanel
 * @xtype   spookyapp-form-chunkgenerator-search
 *
 * @package SpookyApp
 */
SpookyApp.form.ChunkGeneratorSearch = function(config) {
    config = config || {};

    this.contentType = config.contentType || 'movie';
    this.showYearField = config.showYearField !== false;
    this.subtypes = config.subtypes || null;
    this.subtypeLabels = config.subtypeLabels || null;
    this.sourceOptions = config.sourceOptions || null;
    this.showCountryField = config.showCountryField || false;
    this.showLanguageField = config.showLanguageField || false;
    this.flashSportOptions = config.flashSportOptions || null;
    this.sportApiOptions   = config.sportApiOptions   || null;

    Ext.applyIf(config, {
        xtype: 'form',
        cls: 'spookyapp-chunkgen-search-form',
        bodyStyle: 'padding: 10px; background: #f5f5f5;',
        border: false,
        labelWidth: 80,
        labelAlign: 'left',
        layout: 'column',
        defaults: {
            border: false,
            bodyStyle: 'padding: 0 5px;'
        },
        items: this.buildFields(config),
        keys: [{
            key: Ext.EventObject.ENTER,
            fn: this.doSearch,
            scope: this
        }]
    });

    // ── Регистрируем события ─────────────────────────────────
    this.addEvents('search-success', 'search-failure');

    SpookyApp.form.ChunkGeneratorSearch.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.form.ChunkGeneratorSearch, Ext.form.FormPanel, {

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Build Fields                                           ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Построить поля формы.
     *
     * @param {Object} config Конфигурация
     * @return {Array} Массив полей
     */
    buildFields: function(config) {
        var items = [];

        // ── Подтип (для Cinema/Sport) ────────────────────────
        if (this.subtypes && this.subtypes.length > 0) {
            var me = this;
            var subtypeData = [];
            // Use explicit labels when provided (subtypeLabels is [{val,lbl}])
            if (this.subtypeLabels && this.subtypeLabels.length > 0) {
                Ext.each(this.subtypeLabels, function(entry) {
                    subtypeData.push([entry.val, entry.lbl]);
                });
            } else {
                Ext.each(this.subtypes, function(st) {
                    subtypeData.push([
                        st,
                        _('spookyapp.chunkgenerator.subtype_' + st) || st
                    ]);
                });
            }

            items.push({
                columnWidth: 0.2,
                layout: 'form',
                items: [{
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.subtype') || 'Sub-type',
                    name: 'subtype',
                    id: 'spookyapp-chunkgen-search-subtype-' + this.contentType,
                    store: new Ext.data.ArrayStore({
                        fields: ['value', 'label'],
                        data: subtypeData
                    }),
                    displayField: 'label',
                    valueField: 'value',
                    mode: 'local',
                    triggerAction: 'all',
                    editable: false,
                    forceSelection: true,
                    value: this.subtypes[0],
                    anchor: '100%',
                    listeners: {
                        select: {
                            fn: function(combo) {
                                var isFS = (combo.getValue() === 'flashsport');
                                var isSA = (combo.getValue() === 'sportapi');
                                var CT   = me.contentType;
                                if (me.flashSportOptions) {
                                    var cSp = Ext.getCmp('spookyapp-chunkgen-fs-sport-col-' + CT);
                                    var cMo = Ext.getCmp('spookyapp-chunkgen-fs-mode-col-'  + CT);
                                    var cDy = Ext.getCmp('spookyapp-chunkgen-fs-day-col-'   + CT);
                                    var cQu = Ext.getCmp('spookyapp-chunkgen-query-col-'    + CT);
                                    if (isFS) {
                                        if (cSp) { cSp.columnWidth = 0.11; cSp.show(); }
                                        if (cMo) { cMo.columnWidth = 0.11; cMo.show(); }
                                        if (cDy) { cDy.columnWidth = 0.08; cDy.show(); }
                                        if (cQu) { cQu.columnWidth = 0.21; }
                                    } else {
                                        if (cSp) { cSp.columnWidth = 0; cSp.hide(); }
                                        if (cMo) { cMo.columnWidth = 0; cMo.hide(); }
                                        if (cDy) { cDy.columnWidth = 0; cDy.hide(); }
                                        if (cQu) { cQu.columnWidth = 0.35; }
                                    }
                                }
                                if (me.sportApiOptions) {
                                    var cSSp = Ext.getCmp('spookyapp-chunkgen-sa-sport-col-' + CT);
                                    var cSDt = Ext.getCmp('spookyapp-chunkgen-sa-date-col-'  + CT);
                                    var cQu2 = Ext.getCmp('spookyapp-chunkgen-query-col-'    + CT);
                                    if (isSA) {
                                        if (cSSp) { cSSp.columnWidth = 0.13; cSSp.show(); }
                                        if (cSDt) { cSDt.columnWidth = 0.14; cSDt.show(); }
                                        if (cQu2) { cQu2.columnWidth = 0.17; }
                                    } else {
                                        if (cSSp) { cSSp.columnWidth = 0; cSSp.hide(); }
                                        if (cSDt) { cSDt.columnWidth = 0; cSDt.hide(); }
                                        // restore query width if not flashsport
                                        if (!isFS && cQu2) { cQu2.columnWidth = 0.35; }
                                    }
                                }
                                me.doLayout();
                                me.doSearch();
                            }
                        }
                    }
                }]
            });
        }

        // ── Source selector (optional, e.g. for device) ──────
        if (this.sourceOptions && this.sourceOptions.length > 0) {
            var sourceData = [];
            Ext.each(this.sourceOptions, function(s) {
                sourceData.push([s.val, s.lbl]);
            });
            items.push({
                columnWidth: 0.22,
                layout: 'form',
                items: [{
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.source') || 'API Source',
                    name: 'source',
                    id: 'spookyapp-chunkgen-search-source-' + this.contentType,
                    store: new Ext.data.ArrayStore({
                        fields: ['value', 'label'],
                        data: sourceData
                    }),
                    displayField: 'label',
                    valueField: 'value',
                    mode: 'local',
                    triggerAction: 'all',
                    editable: false,
                    forceSelection: true,
                    value: this.sourceOptions[0].val,
                    anchor: '100%',
                    listeners: {
                        select: { fn: function() { this.doSearch(); }, scope: this }
                    }
                }]
            });
        }
        // ── FlashSport options (sport + mode + day) ────────────────
        // Shown only when 'flashsport' subtype is active; start hidden (default = football).
        if (this.flashSportOptions) {
            var fsSportData = [], fsModeData = [];
            Ext.each(this.flashSportOptions.sports, function(s) { fsSportData.push([s.val, s.lbl]); });
            Ext.each(this.flashSportOptions.modes,  function(m) { fsModeData.push([m.val, m.lbl]); });
            // Initial subtype: if it's 'flashsport', show immediately; otherwise hide.
            var fsInitVisible = (this.subtypes && this.subtypes[0] === 'flashsport');

            items.push({
                columnWidth: fsInitVisible ? 0.11 : 0,
                id: 'spookyapp-chunkgen-fs-sport-col-' + this.contentType,
                hidden: !fsInitVisible,
                layout: 'form',
                items: [{
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.fs_sport') || 'Спорт',
                    name: 'fs_sport',
                    id: 'spookyapp-chunkgen-fs-sport-' + this.contentType,
                    store: new Ext.data.ArrayStore({fields: ['value', 'label'], data: fsSportData}),
                    displayField: 'label', valueField: 'value',
                    mode: 'local', triggerAction: 'all', editable: false, forceSelection: true,
                    value: this.flashSportOptions.sports[0].val,
                    anchor: '100%'
                }]
            });

            items.push({
                columnWidth: fsInitVisible ? 0.11 : 0,
                id: 'spookyapp-chunkgen-fs-mode-col-' + this.contentType,
                hidden: !fsInitVisible,
                layout: 'form',
                items: [{
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.fs_mode') || 'Режим',
                    name: 'fs_mode',
                    id: 'spookyapp-chunkgen-fs-mode-' + this.contentType,
                    store: new Ext.data.ArrayStore({fields: ['value', 'label'], data: fsModeData}),
                    displayField: 'label', valueField: 'value',
                    mode: 'local', triggerAction: 'all', editable: false, forceSelection: true,
                    value: this.flashSportOptions.modes[0].val,
                    anchor: '100%'
                }]
            });

            items.push({
                columnWidth: fsInitVisible ? 0.08 : 0,
                id: 'spookyapp-chunkgen-fs-day-col-' + this.contentType,
                hidden: !fsInitVisible,
                layout: 'form',
                items: [{
                    xtype: 'numberfield',
                    fieldLabel: _('spookyapp.chunkgenerator.fs_day') || 'День ±',
                    name: 'fs_day',
                    id: 'spookyapp-chunkgen-fs-day-' + this.contentType,
                    value: 0, minValue: -7, maxValue: 7, allowDecimals: false,
                    anchor: '100%'
                }]
            });
        }        // ── SportAPI7 options (sport + date) ───────────────────
        // Shown only when 'sportapi' subtype is active; start hidden.
        if (this.sportApiOptions) {
            var saSportData = [];
            Ext.each(this.sportApiOptions.sports, function(s) { saSportData.push([s.val, s.lbl]); });
            var saInitVisible = (this.subtypes && this.subtypes[0] === 'sportapi');
            var saToday = new Date();
            var saYear  = saToday.getFullYear();
            var saMon   = ('0' + (saToday.getMonth() + 1)).slice(-2);
            var saDay2  = ('0' + saToday.getDate()).slice(-2);
            var saDefaultDate = saYear + '-' + saMon + '-' + saDay2;

            items.push({
                columnWidth: saInitVisible ? 0.13 : 0,
                id: 'spookyapp-chunkgen-sa-sport-col-' + this.contentType,
                hidden: !saInitVisible,
                layout: 'form',
                items: [{
                    xtype: 'combo',
                    fieldLabel: _('spookyapp.chunkgenerator.sa_sport') || 'Спорт',
                    name: 'sa_sport',
                    id: 'spookyapp-chunkgen-sa-sport-' + this.contentType,
                    store: new Ext.data.ArrayStore({fields: ['value', 'label'], data: saSportData}),
                    displayField: 'label', valueField: 'value',
                    mode: 'local', triggerAction: 'all', editable: false, forceSelection: true,
                    value: this.sportApiOptions.sports[0].val,
                    anchor: '100%'
                }]
            });

            items.push({
                columnWidth: saInitVisible ? 0.14 : 0,
                id: 'spookyapp-chunkgen-sa-date-col-' + this.contentType,
                hidden: !saInitVisible,
                layout: 'form',
                items: [{
                    xtype: 'datefield',
                    fieldLabel: _('spookyapp.chunkgenerator.sa_date') || 'Дата',
                    name: 'sa_date',
                    id: 'spookyapp-chunkgen-sa-date-' + this.contentType,
                    format: 'Y-m-d',
                    value: saDefaultDate,
                    anchor: '100%'
                }]
            });
        }
        // ── Hidden: type ─────────────────────────────────────
        items.push({
            xtype: 'hidden',
            name: 'type',
            value: this.contentType
        });

        // ── Query ────────────────────────────────────────────
        var hasSource = !!(this.sourceOptions && this.sourceOptions.length > 0);
        var hasCountry = !!this.showCountryField;
        var hasLanguage = !!this.showLanguageField;
        var queryWidth;
        if (this.subtypes) {
            var saInitVisible2 = (this.subtypes && this.subtypes[0] === 'sportapi');
            queryWidth = saInitVisible2 ? 0.17 : (fsInitVisible ? 0.21 : 0.35);
        } else if (hasSource) {
            queryWidth = this.showYearField ? 0.28 : 0.43;
            if (hasCountry)  { queryWidth -= 0.11; }
            if (hasLanguage) { queryWidth -= 0.11; }
        } else {
            queryWidth = this.showYearField ? 0.5 : 0.7;
            if (hasCountry)  { queryWidth -= 0.13; }
            if (hasLanguage) { queryWidth -= 0.13; }
        }
        items.push({
            columnWidth: queryWidth,
            id: 'spookyapp-chunkgen-query-col-' + this.contentType,
            layout: 'form',
            items: [{
                xtype: 'textfield',
                fieldLabel: _('spookyapp.chunkgenerator.query') || 'Search',
                name: 'query',
                id: 'spookyapp-chunkgen-search-query-' + this.contentType,
                anchor: '100%',
                allowBlank: false,
                emptyText: _('spookyapp.chunkgenerator.query_empty') || 'Enter search query...'
            }]
        });

        // ── Country (optional) ───────────────────────────────
        if (this.showCountryField) {
            var countryWidth = (hasSource) ? 0.11 : 0.13;
            items.push({
                columnWidth: countryWidth,
                layout: 'form',
                items: [{
                    xtype: 'textfield',
                    fieldLabel: _('spookyapp.chunkgenerator.country') || 'Country',
                    name: 'country',
                    id: 'spookyapp-chunkgen-search-country-' + this.contentType,
                    anchor: '100%',
                    value: 'us',
                    maxLength: 5
                }]
            });
        }

        // ── Language (optional) ──────────────────────────────
        if (this.showLanguageField) {
            items.push({
                columnWidth: 0.13,
                layout: 'form',
                items: [{
                    xtype: 'textfield',
                    fieldLabel: _('spookyapp.chunkgenerator.language') || 'Language',
                    name: 'language',
                    id: 'spookyapp-chunkgen-search-language-' + this.contentType,
                    anchor: '100%',
                    value: 'ru',
                    maxLength: 5
                }]
            });
        }

        // ── Year (optional) ──────────────────────────────────
        if (this.showYearField) {
            items.push({
                columnWidth: 0.15,
                layout: 'form',
                items: [{
                    xtype: 'numberfield',
                    fieldLabel: _('spookyapp.chunkgenerator.year') || 'Year',
                    name: 'year',
                    id: 'spookyapp-chunkgen-search-year-' + this.contentType,
                    anchor: '100%',
                    allowBlank: true,
                    minValue: 1900,
                    maxValue: new Date().getFullYear() + 5,
                    allowDecimals: false,
                    emptyText: _('spookyapp.chunkgenerator.year_any') || 'Any'
                }]
            });
        }

        // ── Page (hidden, для пагинации) ─────────────────────
        items.push({
            xtype: 'hidden',
            name: 'page',
            value: 1
        });

        // ── Search Button ────────────────────────────────────
        items.push({
            columnWidth: 0.10,
            layout: 'form',
            bodyStyle: '',
            items: [{
                xtype: 'button',
                text: _('spookyapp.chunkgenerator.search_btn') || 'Search',
                cls: 'primary-button',
                width: '80%',
                handler: this.doSearch,
                scope: this
            }]
        });

        // ── Clear Button ─────────────────────────────────────
        items.push({
            columnWidth: 0.09,
            layout: 'form',
            bodyStyle: '',
            items: [{
                xtype: 'button',
                text: _('spookyapp.chunkgenerator.clear_btn') || 'Clear',
                width: '80%',
                handler: this.doClear,
                scope: this
            }]
        });

        return items;
    },

    // ╔═════════════════════════════════════════════════════════╗
    // ║  Actions                                                ║
    // ╚═════════════════════════════════════════════════════════╝

    /**
     * Выполнить поиск.
     */
    doSearch: function() {
        if (!this.getForm().isValid()) {
            return;
        }

        var values = this.getForm().getValues();

        // ── Определяем тип для API ───────────────────────────
        var searchType = values.type;
        // ExtJS 3 getValues() returns display text for combos — use findField().getValue() for actual value
        if (this.subtypes && this.subtypes.length > 0) {
            var stf = this.getForm().findField('subtype');
            if (stf) { var stVal = stf.getValue(); if (stVal) { searchType = stVal; } }
        }

        var params = {
            action: 'chunkgenerator/search',
            type: searchType,
            query: values.query,
            page: values.page || 1
        };

        if (values.year) {
            params.year = values.year;
        }
        if (this.showCountryField) {
            params.country = values.country || 'us';
        }
        if (this.showLanguageField) {
            params.language = values.language || 'ru';
        }
        // ExtJS 3 form.getValues() serialises DOM text — combo returns display label, not valueField.
        // Read combo value directly via findField().getValue() to get the actual stored value.
        if (this.sourceOptions && this.sourceOptions.length > 0) {
            var sf = this.getForm().findField('source');
            if (sf) {
                var sourceVal = sf.getValue();
                if (sourceVal) { params.source = sourceVal; }
            }
        }

        // ── FlashSport params (only when flashsport subtype active) ──────────────
        if (this.flashSportOptions) {
            var stFld = this.getForm().findField('subtype');
            if (stFld && stFld.getValue() === 'flashsport') {
                var fsSportField = this.getForm().findField('fs_sport');
                var fsModeField  = this.getForm().findField('fs_mode');
                var fsDayField   = this.getForm().findField('fs_day');
                if (fsSportField) { params.fs_sport = fsSportField.getValue() || 1; }
                if (fsModeField)  { params.fs_mode  = fsModeField.getValue()  || 'match'; }
                if (fsDayField)   { params.fs_day   = parseInt(fsDayField.getValue(), 10) || 0; }
            }
        }

        // ── SportAPI7 params (only when sportapi subtype active) ────────────
        if (this.sportApiOptions) {
            var stFld2 = this.getForm().findField('subtype');
            if (stFld2 && stFld2.getValue() === 'sportapi') {
                var saSportField = this.getForm().findField('sa_sport');
                var saDateField  = this.getForm().findField('sa_date');
                if (saSportField) { params.sa_sport = saSportField.getValue() || 'football'; }
                if (saDateField) {
                    var saDateVal = saDateField.getValue();
                    if (saDateVal instanceof Date) {
                        var sy = saDateVal.getFullYear();
                        var sm = ('0' + (saDateVal.getMonth() + 1)).slice(-2);
                        var sd = ('0' + saDateVal.getDate()).slice(-2);
                        params.sa_date = sy + '-' + sm + '-' + sd;
                    } else {
                        params.sa_date = (typeof saDateVal === 'string') ? saDateVal : '';
                    }
                }
            }
        }

        // ── Маска загрузки ───────────────────────────────────
        var mask = new Ext.LoadMask(this.ownerCt.getEl(), {
            msg: _('spookyapp.chunkgenerator.searching') || 'Searching...'
        });
        mask.show();

        MODx.Ajax.request({
            url: SpookyApp.config.connector_url,
            params: params,
            listeners: {
                success: {
                    fn: function(r) {
                        mask.hide();
                        var data = r.object || {};
                        this.fireEvent(
                            'search-success',
                            data.results || [],
                            data.type || searchType,
                            values.query
                        );

                        // ── Статус ───────────────────────────
                        var total = data.total || 0;
                        MODx.msg.status({
                            title: _('success'),
                            message: (_('spookyapp.chunkgenerator.found') || 'Found: ') + total
                        });
                    },
                    scope: this
                },
                failure: {
                    fn: function(r) {
                        mask.hide();
                        this.fireEvent('search-failure', r.message || 'Search failed');
                        MODx.msg.alert(
                            _('error'),
                            r.message || _('spookyapp.chunkgenerator.err_search_failed')
                        );
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Очистить форму.
     */
    doClear: function() {
        this.getForm().reset();
    }
});
Ext.reg('spookyapp-form-chunkgenerator-search', SpookyApp.form.ChunkGeneratorSearch);
