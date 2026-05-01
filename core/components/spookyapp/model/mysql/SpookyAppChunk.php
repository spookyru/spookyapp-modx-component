<?php
namespace spookyapp\mysql;

use xPDO\xPDO;

class SpookyAppChunk extends \spookyapp\SpookyAppChunk
{

    public static $metaMap = array (
        'package' => 'spookyapp',
        'version' => '3.0',
        'table' => 'spookyapp_chunks',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'external_id' => '',
            'content_type' => 'movie',
            'name' => '',
            'data' => '',
            'source' => 'manual',
            'createdon' => 0,
            'updatedon' => 0,
        ),
        'fieldMeta' => 
        array (
            'external_id' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '255',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'comment' => 'Внешний ID (tmdb_id, rawg slug и т.д.)',
            ),
            'content_type' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '50',
                'phptype' => 'string',
                'null' => false,
                'default' => 'movie',
                'comment' => 'Тип: movie, tvshow, game, device, person, product',
            ),
            'name' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '512',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Название (для быстрого поиска без разбора JSON)',
            ),
            'data' => 
            array (
                'dbtype' => 'mediumtext',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Полные данные в формате JSON',
            ),
            'source' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '50',
                'phptype' => 'string',
                'null' => true,
                'default' => 'manual',
                'comment' => 'Источник: tmdb, rawg, manual…',
            ),
            'createdon' => 
            array (
                'dbtype' => 'int',
                'precision' => '20',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'Unix-timestamp создания записи',
            ),
            'updatedon' => 
            array (
                'dbtype' => 'int',
                'precision' => '20',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'Unix-timestamp последнего обновления',
            ),
        ),
        'indexes' => 
        array (
            'unique_external' => 
            array (
                'alias' => 'unique_external',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'external_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                    'content_type' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'content_type_idx' => 
            array (
                'alias' => 'content_type_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'content_type' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'source_idx' => 
            array (
                'alias' => 'source_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'source' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
    );

}
