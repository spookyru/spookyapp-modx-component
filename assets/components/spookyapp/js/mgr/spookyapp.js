let SpookyApp = function (config) {
    config = config || {};
    SpookyApp.superclass.constructor.call(this, config);
};
Ext.extend(SpookyApp, Ext.Component, {
    page: {}, window: {}, grid: {}, tree: {}, panel: {}, combo: {}, config: {}, view: {}, utils: {}
});
Ext.reg('spookyapp', SpookyApp);

SpookyApp = new SpookyApp();