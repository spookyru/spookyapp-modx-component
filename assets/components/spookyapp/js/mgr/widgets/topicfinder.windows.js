/**
 * SpookyApp — TopicFinder Windows.
 *
 * Окно «Рерайт через AI»: позволяет задать режим, тон, язык,
 * объём и запустить переписывание темы через Yandex GPT.
 *
 * @class SpookyApp.window.RewriteAI
 * @extends MODx.Window
 * @xtype spookyapp-window-rewrite-ai
 *
 * @package SpookyApp
 * @subpackage JS\Manager\Widgets
 */
SpookyApp.window.RewriteAI = function (config) {
    config = config || {};

    Ext.applyIf(config, {
        id:         'spookyapp-window-rewrite-ai',
        title:      _('spookyapp.rewrite.title') || 'Рерайт через AI',
        width:      560,
        autoHeight: true,
        url:        SpookyApp.config.connector_url,
        action:     'topicfinder/rewrite',
        baseParams: {
            action: 'topicfinder/rewrite'
        },
        fields:     this.getFields(config),
        keys: [{
            key:   Ext.EventObject.ENTER,
            shift: true,
            fn:    function () { this.submit(); },
            scope: this
        }]
    });

    // config.success is called by MODx.Window.submit() before auto-hide.
    // We just show a brief status — the full result appears in the details panel
    // via the 'success' event listener in onRewriteWithAI.
    if (!config.success) {
        config.success = function (frm, a) {
            var result  = (a && a.result) ? a.result : {};
            var stats   = (result.object && result.object.stats) ? result.object.stats : {};
            var chars   = stats.rewrite_length ? (' · ' + stats.rewrite_length + ' символов') : '';
            var secs    = stats.duration_sec   ? (' · ' + stats.duration_sec + 'с')           : '';
            MODx.msg.status({
                title:   _('spookyapp.rewrite.result_title') || 'Рерайт готов',
                message: (_('spookyapp.rewrite.done') || 'Результат сохранён в теме') + chars + secs,
                delay:   4
            });
        };
    }

    // Show failure messages properly (MODx.Window otherwise silently ignores non-field errors)
    if (!config.listeners) {
        config.listeners = {};
    }
    if (!config.listeners.failure) {
        config.listeners.failure = {
            fn: function (data) {
                var a   = data.a || {};
                var msg = (a.result && a.result.message) ? a.result.message
                        : (_('spookyapp.rewrite.error') || 'Ошибка при генерации рерайта.');
                MODx.msg.alert(_('spookyapp.error') || 'Ошибка', msg);
            },
            scope: this
        };
    }

    SpookyApp.window.RewriteAI.superclass.constructor.call(this, config);
};

Ext.extend(SpookyApp.window.RewriteAI, MODx.Window, {

    getFields: function (config) {
        var rec    = config.record || {};
        var topicId = rec.id || rec.topic_id || 0;
        var title   = rec.title || '';

        return [
            // ── Hidden: topic id ──────────────────────────────
            {
                xtype: 'hidden',
                name:  'id',
                value: topicId
            },

            // ── Инфо о теме ───────────────────────────────────
            {
                xtype:     'displayfield',
                fieldLabel: _('spookyapp.rewrite.topic') || 'Тема',
                value:     Ext.util.Format.ellipsis(title, 80),
                cls:       'spookyapp-rewrite-topic-label'
            },

            // ── Режим рерайта ─────────────────────────────────
            {
                xtype:      'combo',
                fieldLabel: _('spookyapp.rewrite.mode') || 'Режим',
                name:       'mode',
                id:         'spookyapp-rewrite-mode',
                store: new Ext.data.SimpleStore({
                    fields: ['value', 'text'],
                    data: [
                        ['article', _('spookyapp.rewrite.mode.article') || 'Статья'],
                        ['news',    _('spookyapp.rewrite.mode.news')    || 'Новость'],
                        ['social',  _('spookyapp.rewrite.mode.social')  || 'Соцсети'],
                        ['seo',     _('spookyapp.rewrite.mode.seo')     || 'SEO-пакет'],
                        ['title',   _('spookyapp.rewrite.mode.title')   || '5 заголовков'],
                        ['custom',  _('spookyapp.rewrite.mode.custom')  || 'Свой промпт']
                    ]
                }),
                displayField:   'text',
                valueField:     'value',
                value:          'article',
                triggerAction:  'all',
                editable:       false,
                forceSelection: true,
                mode:           'local',
                anchor:         '100%',
                listeners: {
                    select: {
                        fn: function (combo, record) {
                            var customPromptRow = Ext.getCmp('spookyapp-rewrite-custom-prompt-row');
                            if (customPromptRow) {
                                if (record.get('value') === 'custom') {
                                    customPromptRow.show();
                                } else {
                                    customPromptRow.hide();
                                }
                                this.doLayout();
                            }
                        },
                        scope: this
                    }
                }
            },

            // ── Тон текста ────────────────────────────────────
            {
                xtype:      'combo',
                fieldLabel: _('spookyapp.rewrite.tone') || 'Тон',
                name:       'tone',
                store: new Ext.data.SimpleStore({
                    fields: ['value', 'text'],
                    data: [
                        ['neutral',     _('spookyapp.rewrite.tone.neutral')     || 'Нейтральный'],
                        ['formal',      _('spookyapp.rewrite.tone.formal')      || 'Деловой'],
                        ['casual',      _('spookyapp.rewrite.tone.casual')      || 'Разговорный'],
                        ['enthusiastic',_('spookyapp.rewrite.tone.enthusiastic')|| 'Эмоциональный'],
                        ['analytical',  _('spookyapp.rewrite.tone.analytical')  || 'Аналитический']
                    ]
                }),
                displayField:   'text',
                valueField:     'value',
                value:          'neutral',
                triggerAction:  'all',
                editable:       false,
                forceSelection: true,
                mode:           'local',
                anchor:         '100%'
            },

            // ── Язык ──────────────────────────────────────────
            {
                xtype:      'combo',
                fieldLabel: _('spookyapp.rewrite.language') || 'Язык',
                name:       'language',
                store: new Ext.data.SimpleStore({
                    fields: ['value', 'text'],
                    data: [
                        ['ru', 'Русский'],
                        ['en', 'English']
                    ]
                }),
                displayField:   'text',
                valueField:     'value',
                value:          'ru',
                triggerAction:  'all',
                editable:       false,
                forceSelection: true,
                mode:           'local',
                anchor:         '100%'
            },

            // ── Объём ─────────────────────────────────────────
            {
                xtype:  'panel',
                layout: 'column',
                border: false,
                anchor: '100%',
                items: [
                    {
                        columnWidth: 0.5,
                        layout: 'form',
                        border: false,
                        labelWidth: 120,
                        items: [{
                            xtype:      'numberfield',
                            fieldLabel: _('spookyapp.rewrite.min_length') || 'Минимум символов',
                            name:       'min_length',
                            value:      500,
                            minValue:   50,
                            maxValue:   2000,
                            anchor:     '95%'
                        }]
                    },
                    {
                        columnWidth: 0.5,
                        layout: 'form',
                        border: false,
                        labelWidth: 120,
                        items: [{
                            xtype:      'numberfield',
                            fieldLabel: _('spookyapp.rewrite.max_length') || 'Максимум символов',
                            name:       'max_length',
                            value:      5000,
                            minValue:   200,
                            maxValue:   15000,
                            anchor:     '100%'
                        }]
                    }
                ]
            },

            // ── Температура ───────────────────────────────────
            {
                xtype:      'numberfield',
                fieldLabel: _('spookyapp.rewrite.temperature') || 'Температура (0–1)',
                name:       'temperature',
                value:      0.6,
                minValue:   0,
                maxValue:   1,
                decimalPrecision: 2,
                anchor:     '100%'
            },

            // ── Опции ─────────────────────────────────────────
            {
                xtype:    'xcheckbox',
                boxLabel: _('spookyapp.rewrite.save_to_topic') || 'Сохранить результат в тему',
                name:     'save_to_topic',
                checked:  true
            },
            {
                xtype:    'xcheckbox',
                boxLabel: _('spookyapp.rewrite.force') || 'Перегенерировать, если уже есть',
                name:     'force',
                checked:  false
            },

            // ── Свой промпт (скрыт, показывается для mode=custom) ───
            {
                xtype:     'panel',
                id:        'spookyapp-rewrite-custom-prompt-row',
                border:    false,
                hidden:    true,
                layout:    'form',
                anchor:    '100%',
                labelWidth: 1,
                items: [{
                    xtype:      'textarea',
                    fieldLabel: '',
                    name:       'prompt',
                    height:     100,
                    anchor:     '100%',
                    emptyText:  _('spookyapp.rewrite.custom_prompt_hint')
                        || 'Напишите свой промпт. Используйте {title}, {description}, {source}, {category}.'
                }]
            }
        ];
    },

});

Ext.reg('spookyapp-window-rewrite-ai', SpookyApp.window.RewriteAI);
