SpookyApp.window.CreateItem = function (config) {
    config = config || {};
    if (!config.id) {
        config.id = 'spookyapp-item-window-create';
    }
    Ext.applyIf(config, {
        title: _('spookyapp_item_create'),
        width: 550,
        autoHeight: true,
        url: SpookyApp.config.connector_url,
        action: 'SpookyApp\\Processors\\Item\\Create',
        fields: this.getFields(config),
        keys: [{
            key: Ext.EventObject.ENTER, shift: true, fn: function () {
                this.submit()
            }, scope: this
        }]
    });
    SpookyApp.window.CreateItem.superclass.constructor.call(this, config);
};
Ext.extend(SpookyApp.window.CreateItem, MODx.Window, {

    getFields: function (config) {
        return [{
            xtype: 'textfield',
            fieldLabel: _('spookyapp_item_name'),
            name: 'name',
            id: config.id + '-name',
            anchor: '99%',
            allowBlank: false,
        }, {
            xtype: 'textarea',
            fieldLabel: _('spookyapp_item_description'),
            name: 'description',
            id: config.id + '-description',
            height: 150,
            anchor: '99%'
        }, {
            xtype: 'xcheckbox',
            boxLabel: _('spookyapp_item_active'),
            name: 'active',
            id: config.id + '-active',
            checked: true,
        }];
    },

    loadDropZones: function () {
    }

});
Ext.reg('spookyapp-item-window-create', SpookyApp.window.CreateItem);


SpookyApp.window.UpdateItem = function (config) {
    config = config || {};
    if (!config.id) {
        config.id = 'spookyapp-item-window-update';
    }
    Ext.applyIf(config, {
        title: _('spookyapp_item_update'),
        width: 550,
        autoHeight: true,
        url: SpookyApp.config.connector_url,
        action: 'SpookyApp\\Processors\\Item\\Update',
        fields: this.getFields(config),
        keys: [{
            key: Ext.EventObject.ENTER, shift: true, fn: function () {
                this.submit()
            }, scope: this
        }]
    });
    SpookyApp.window.UpdateItem.superclass.constructor.call(this, config);
};
Ext.extend(SpookyApp.window.UpdateItem, MODx.Window, {

    getFields: function (config) {
        return [{
            xtype: 'hidden',
            name: 'id',
            id: config.id + '-id',
        }, {
            xtype: 'textfield',
            fieldLabel: _('spookyapp_item_name'),
            name: 'name',
            id: config.id + '-name',
            anchor: '99%',
            allowBlank: false,
        }, {
            xtype: 'textarea',
            fieldLabel: _('spookyapp_item_description'),
            name: 'description',
            id: config.id + '-description',
            anchor: '99%',
            height: 150,
        }, {
            xtype: 'xcheckbox',
            boxLabel: _('spookyapp_item_active'),
            name: 'active',
            id: config.id + '-active',
        }];
    },

    loadDropZones: function () {
    }

});
Ext.reg('spookyapp-item-window-update', SpookyApp.window.UpdateItem);