<?php
namespace SpookyApp\Model\mysql;

use xPDO\xPDO;

/**
 * MySQL-specific metaMap for SpookyAppChunk.
 *
 * Таблица: {prefix}spookyapp_chunks
 * Префикс БД подставляется xPDO автоматически из конфига MODX.
 *
 * @package SpookyApp
 */
class SpookyAppChunk extends \SpookyApp\Model\SpookyAppChunk
{
    public static $metaMap = [
        'package'   => 'SpookyApp\\Model',
        'version'   => '3.0',
        'table'     => 'spookyapp_chunks',
        'extends'   => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => ['engine' => 'InnoDB'],
        'fields'    => [
            'type'        => 'movie',
            'external_id' => '',
            'title'       => '',
            'data'        => '',
            'created_at'  => null,
            'updated_at'  => null,
        ],
        'fieldMeta' => [
            'type' => [
                'dbtype'    => 'varchar',
                'precision' => '50',
                'phptype'   => 'string',
                'null'      => false,
                'default'   => 'movie',
                'comment'   => 'Тип контента: movie, tv, game, device, person, product, sport',
            ],
            'external_id' => [
                'dbtype'    => 'varchar',
                'precision' => '255',
                'phptype'   => 'string',
                'null'      => false,
                'default'   => '',
                'comment'   => 'Внешний ID (tmdb_id, rawg slug и т.д.)',
            ],
            'title' => [
                'dbtype'    => 'varchar',
                'precision' => '512',
                'phptype'   => 'string',
                'null'      => true,
                'default'   => '',
                'comment'   => 'Название (для быстрого поиска без разбора JSON)',
            ],
            'data' => [
                'dbtype'  => 'mediumtext',
                'phptype' => 'string',
                'null'    => true,
                'default' => '',
                'comment' => 'Полные данные в формате JSON',
            ],
            'created_at' => [
                'dbtype'  => 'datetime',
                'phptype' => 'string',
                'null'    => true,
                'default' => null,
                'comment' => 'Дата создания записи (Y-m-d H:i:s)',
            ],
            'updated_at' => [
                'dbtype'  => 'datetime',
                'phptype' => 'string',
                'null'    => true,
                'default' => null,
                'comment' => 'Дата последнего обновления (Y-m-d H:i:s)',
            ],
        ],
        'indexes' => [
            'unique_type_external' => [
                'alias'   => 'unique_type_external',
                'primary' => false,
                'unique'  => true,
                'type'    => 'BTREE',
                'columns' => [
                    'type'        => ['length' => '', 'collation' => 'A', 'null' => false],
                    'external_id' => ['length' => '', 'collation' => 'A', 'null' => false],
                ],
            ],
            'type_idx' => [
                'alias'   => 'type_idx',
                'primary' => false,
                'unique'  => false,
                'type'    => 'BTREE',
                'columns' => [
                    'type' => ['length' => '', 'collation' => 'A', 'null' => false],
                ],
            ],
        ],
    ];
}
