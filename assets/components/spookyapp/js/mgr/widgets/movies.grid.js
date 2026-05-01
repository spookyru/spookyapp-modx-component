SpookyApp.MoviesGrid = SpookyApp.MoviesGrid || {};

SpookyApp.MoviesGrid.Items = function (config) {
    config = config || {};
    if (!config.id) {
        config.id = 'movies-grid-';
    }
    Ext.applyIf(config, {
        url: SpookyApp.config.connector_url,
        fields: this.getFields(config),
      columns: this.getColumns(config),
     
        tbar: this.getTopBar(config),
        sm: new Ext.grid.CheckboxSelectionModel(),
        baseParams: {
          action: 'SpookyApp\\Processors\\Movies\\GetList',
        },
        listeners: {
            rowDblClick: function (grid, rowIndex, e) {
                const row = grid.store.getAt(rowIndex);
                this.updateItem(grid, e, row);
          },
          viewready: function (grid) {
            SpookyApp.utils.lazyLoadImages(grid);
          }
        },
      viewConfig: {
        autoHeight: true,       
        deferredRender: true,
        loadMask: true,
        forceFit: true,
        enableRowBody: true,
        autoFill: true,
        showPreview: false,
        scrollOffset: 0,
        autoload: false,
        trackMouseOver: true,
            getRowClass: function (rec) {
                return !rec.data.active
                    ? 'movies-grid-row-disabled'
                    : '';
            }
        },
        paging: true,
      remoteSort: true,
      resizable: true,
    });
    SpookyApp.MoviesGrid.Items.superclass.constructor.call(this, config);

    // Clear selection on grid refresh
    this.store.on('load', function () {
        if (this._getSelectedIds().length) {
            this.getSelectionModel().clearSelections();
        }
    }, this);
};
Ext.extend(SpookyApp.MoviesGrid.Items, MODx.grid.Grid, {
    windows: {},

    getMenu: function (grid, rowIndex) {
        const ids = this._getSelectedIds();

        const row = grid.getStore().getAt(rowIndex);
        const menu = SpookyApp.utils.getMenu(row.data['actions'], this, ids);
     // console.log(row.data['actions']);
        this.addContextMenuItem(menu);
    },

    createItem: function (btn, e) {
        const w = MODx.load({
            xtype: 'movies-item-window-create',
            id: Ext.id(),
            listeners: {
                success: {
                    fn: function () {
                        this.refresh();
                    }, scope: this
                }
            }
        });
        w.reset();
        w.setValues({active: true});
        w.show(e.target);
  },
  upcoming: function (btn, e) {
    const w = MODx.load({
        xtype: 'movies-upcoming',
        id: Ext.id(),
        listeners: {
            success: {
                fn: function () {
                    this.refresh();
                }, scope: this
            }
        }
    });
    w.reset();
    w.setValues({active: true});
    w.show(e.target);
},

    updateItem: function (btn, e, row) {
        if (typeof(row) != 'undefined') {
            this.menu.record = row.data;
        }
        else if (!this.menu.record) {
            return false;
        }
        const id = this.menu.record.id;

        MODx.Ajax.request({
            url: this.config.url,
            params: {
              action: 'SpookyApp\\Processors\\Movies\\Get',
                id: id
            },
            listeners: {
                success: {
                    fn: function (r) {
                        const w = MODx.load({
                            xtype: 'movies-item-window-update',
                            id: Ext.id(),
                            record: r,
                            listeners: {
                                success: {
                                    fn: function () {
                                        this.refresh();
                                    }, scope: this
                              },
                              show: function () {
                            //    console.log('Window show event triggered');
                              },
                              afterrender: function () {
                            //    console.log('Window afterrender event triggered');
                              }
                            }
                        });
                        w.reset();
                        w.setValues(r.object);
                        w.show(e.target);
                    }, scope: this
                }
            }
        });
    },

    removeItem: function () {
        const ids = this._getSelectedIds();
        if (!ids.length) {
            return false;
        }
        MODx.msg.confirm({
            title: ids.length > 1
                ? _('movies_items_remove')
                : _('movies_item_remove'),
            text: ids.length > 1
                ? _('movies_items_remove_confirm')
                : _('movies_item_remove_confirm'),
            url: this.config.url,
            params: {
              action: 'SpookyApp\\Processors\\Movies\\Remove',
                ids: Ext.util.JSON.encode(ids),
            },
            listeners: {
                success: {
                    fn: function () {
                        this.refresh();
                    }, scope: this
                }
            }
        });
        return true;
    },

    disableItem: function () {
        const ids = this._getSelectedIds();
        if (!ids.length) {
            return false;
        }
        MODx.Ajax.request({
            url: this.config.url,
            params: {
              action: 'SpookyApp\\Processors\\Movies\\Disable',
                ids: Ext.util.JSON.encode(ids),
            },
            listeners: {
                success: {
                    fn: function () {
                        this.refresh();
                    }, scope: this
                }
            }
        })
    },

    enableItem: function () {
        const ids = this._getSelectedIds();
        if (!ids.length) {
            return false;
        }
        MODx.Ajax.request({
            url: this.config.url,
            params: {
              action: 'SpookyApp\\Processors\\Movies\\Enable',
                ids: Ext.util.JSON.encode(ids),
            },
            listeners: {
                success: {
                    fn: function () {
                        this.refresh();
                    }, scope: this
                }
            }
        })
    },

  disableIndexItem: function () {
    const ids = this._getSelectedIds();
    if (!ids.length) {
      return false;
    }
    MODx.Ajax.request({
      url: this.config.url,
      params: {
        action: 'SpookyApp\\Processors\\Movies\\DisableIndex',
        ids: Ext.util.JSON.encode(ids),
      },
      listeners: {
        success: {
          fn: function () {
            this.refresh();
          }, scope: this
        }
      }
    })
  },

  enableIndexItem: function () {
    const ids = this._getSelectedIds();
    if (!ids.length) {
      return false;
    }
    MODx.Ajax.request({
      url: this.config.url,
      params: {
        action: 'SpookyApp\\Processors\\Movies\\EnableIndex',
        ids: Ext.util.JSON.encode(ids),
      },
      listeners: {
        success: {
          fn: function () {
            this.refresh();
          }, scope: this
        }
      }
    })
  },
    getFields: function () {
      return ['id', 'title', 'overview', 'poster_path', 'backdrop_path','release_date', 'view_index', 'active', 'actions'];
    },

    getColumns: function () {
      return [
        {
        header: _('movies_Movies_id'),
        dataIndex: 'id',
        sortable: true,
        width: 100
      },
        {
            header: _('movies_Movies_poster'),
          dataIndex: 'poster_path',
             renderer: movies.utils.renderImages,
            sortable: false,
            width: 120,
          },  
        {
          header: _('movies_Movies_backdrop'),
          dataIndex: 'backdrop_path',
            renderer: SpookyApp.utils.renderImages,
            sortable: false,
            width: 250,
          },
            {
            header: _('movies_Movies_name'),
            dataIndex: 'title',
            sortable: true,
            width: 200,
        }, {
          header: _('movies_Movies_overview'),
          dataIndex: 'overview',
            sortable: false,
            width: 350,
        },
        {
          header: _('movies_Movies_releaseDate'),
          renderer: SpookyApp.utils.renderDate,
          dataIndex: 'release_date',
            sortable: false,
            width: 250,
        },
        {
          header: _('movies_item_viewIndex'),
          dataIndex: 'view_index',
          renderer: SpookyApp.utils.renderBoolean,
          sortable: true,
          width: 100,
        },
        {
            header: _('movies_item_active'),
            dataIndex: 'active',
            renderer: SpookyApp.utils.renderBoolean,
            sortable: true,
            width: 100,
        }, {
            header: _('movies_grid_actions'),
            dataIndex: 'actions',
            renderer: SpookyApp.utils.renderActions,
            sortable: false,
            width: 100,
            id: 'actions'
        }];
    },

    getTopBar: function () {
        return [{
            text: '<i class="icon icon-plus"></i>&nbsp;' + _('movies_item_create'),
            handler: this.createItem,
            scope: this
        },
        {
          text: '<i class="icon icon-newspaper"></i>&nbsp;' + 'Обновить ожидаемые премьеры',
          handler: this.upcoming,
          scope: this
      },  '->', {
            xtype: 'movies-field-search',
            width: 250,
            listeners: {
                search: {
                    fn: function (field) {
                        this._doSearch(field);
                    }, scope: this
                },
                clear: {
                    fn: function (field) {
                        field.setValue('');
                        this._clearSearch();
                    }, scope: this
                },
            }
        }];
    },

    onClick: function (e) {
        const elem = e.getTarget();
        if (elem.nodeName == 'BUTTON') {
            const row = this.getSelectionModel().getSelected();
            if (typeof(row) != 'undefined') {
                const action = elem.getAttribute('action');
                if (action == 'showMenu') {
                    const ri = this.getStore().find('id', row.id);
                    return this._showMenu(this, ri, e);
                }
                else if (typeof this[action] === 'function') {
                    this.menu.record = row.data;
                    return this[action](this, e);
                }
            }
        }
        return this.processEvent('click', e);
    },

    _getSelectedIds: function () {
        const ids = [];
        const selected = this.getSelectionModel().getSelections();

        for (const i in selected) {
            if (!selected.hasOwnProperty(i)) {
                continue;
            }
            ids.push(selected[i]['id']);
        }

        return ids;
    },

    _doSearch: function (tf) {
        this.getStore().baseParams.query = tf.getValue();
        this.getBottomToolbar().changePage(1);
    },

    _clearSearch: function () {
        this.getStore().baseParams.query = '';
        this.getBottomToolbar().changePage(1);
    },
});
Ext.reg('spooky-app-movie', SpookyApp.MoviesGrid.Items);
