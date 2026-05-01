SpookyApp.panel.Services = function (config) {
  config = config || {};
  Ext.apply(config, {
    //baseCls: 'modx-formpanel',
    layout: 'anchor',
   // hideMode: 'offsets',
    items: [{
      html: '<h2>Выбор сервиса</h2> ',
      cls: '',
      style: { margin: '15px 0' }
    }, {
      html: 'Обьеденил в одном пакете все сервисы, что я писал для других своих сайтов. А также адаптировал под MODX Revolution. Здесь можно будет получить контент с различных API сервисов, таких как TMDB, Football-Data.org, Steam и других. Также можно будет получать данные из локальных файлов. И сформировать снипет.',
   // cls: 'panel-desc',
  }]
    });
    SpookyApp.panel.Services.superclass.constructor.call(this, config);
};
Ext.extend(SpookyApp.panel.Services, MODx.Panel);
Ext.reg('spooky-app-services', SpookyApp.panel.Services);