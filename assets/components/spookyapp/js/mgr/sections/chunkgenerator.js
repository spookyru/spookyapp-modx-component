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