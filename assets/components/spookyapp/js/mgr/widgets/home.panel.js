SpookyApp.panel.Home = function (config) {
    config = config || {};
    Ext.apply(config, {
        baseCls: 'modx-formpanel',
      layout: 'anchor',
        style: 'padding: 33px;',
        /*
         stateful: true,
         stateId: 'spookyapp-panel-home',
         stateEvents: ['tabchange'],
         getState:function() {return {activeTab:this.items.indexOf(this.getActiveTab())};},
         */
         hideMode: 'offsets',
      tbar: [{
        text: 'Фильмы',
        handler: function() {
          window.location.href = '/manager/?a=movies&namespace=spookyapp';
        }
    }, {
        text: 'Сериалы',
        handler: function() {
          window.location.href = '/manager/?a=series&namespace=spookyapp';
        }
    }, {
        text: 'Футбол',
        handler: function() {
            // Открыть страницу внутри текущего приложения (например, локальный HTML файл)
            window.location.href = '/manager/?a=cinema&namespace=spookyapp';
        }
        },
      {
        text: 'Игры',
        handler: function() {
            // Открыть страницу внутри текущего приложения (например, локальный HTML файл)
            window.location.href = '/manager/?a=games&namespace=spookyapp';
        }
        },
      {
        text: 'Erid? Контент?',
        handler: function() {
            // Открыть страницу внутри текущего приложения (например, локальный HTML файл)
            window.location.href = '/manager/?a=test&namespace=spookyapp';
        }
    }],
        items: [{
            html: '<h2>' + _('spookyapp') + '</h2> <hr> __________ ----- ',
            cls: '',
            style: {margin: '15px 0'}
        }, {
            xtype: 'modx-tabs',
            defaults: {border: false, autoHeight: true},
            border: true,
            hideMode: 'offsets',
          items: [
            {//tab1
                title: _('spookyapp_welcome'),
                layout: 'anchor',
                items: [{
                    html: 'Панель запросов к удаленным API сервисом для формирования контента на сайте.',
                    cls: 'panel-desc',
                }, {
                  // xtype: 'spookyapp-grid-items',
                    xtype: 'spooky-app-services',
                    cls: 'main-wrapper',
                }]
            },
           {//tab2
                title: _('spookyapp_tmdb'),
                layout: 'anchor',
                items: [{
                    html: 'Формирование контента на основе API TMDB и кинотеатра',
                    cls: 'panel-desc',
                }, {
                  // xtype: 'spookyapp-grid-items',
         //           xtype: 'spooky-app-movie',
                    cls: 'main-wrapper',
                }]
            },
            {//tab3
                title: _('spookyapp_football'),
                layout: 'anchor',
                items: [{
                    html: 'формирование контента на основе API apifootball',
                    cls: 'panel-desc',
                }, {
                  // xtype: 'spookyapp-grid-items',
        //            xtype: 'spooky-app-football',
                    cls: 'main-wrapper',
                }]
            },
             {//tab4
                title: _('spookyapp_games'),
                layout: 'anchor',
                items: [{
                    html: 'Формирование контента на основе API igndb',
                    cls: 'panel-desc',
                }, {
                  // xtype: 'spookyapp-grid-items',
       //             xtype: 'spooky-app-games',
                    cls: 'main-wrapper',
                }]
            },
            ]
        }]
    });
    SpookyApp.panel.Home.superclass.constructor.call(this, config);
};
Ext.extend(SpookyApp.panel.Home, MODx.Panel);
Ext.reg('spookyapp-panel-home', SpookyApp.panel.Home);
