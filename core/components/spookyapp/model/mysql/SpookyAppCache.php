<?php
namespace spookyapp\mysql;

use xPDO\xPDO;

class SpookyAppCache extends \spookyapp\SpookyAppCache
{

    public static $metaMap = array (
        'package' => 'spookyapp',
        'version' => '3.0',
        'table' => 'spookyapp_cache',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'cache_key' => '',
            'cache_group' => 'default',
            'data' => '',
            'expires_at' => 0,
            'createdon' => 0,
        ),
        'fieldMeta' => 
        array (
            'cache_key' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '512',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'comment' => 'Уникальный ключ кеша (sha256 URL или составной)',
            ),
            'cache_group' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => 'default',
                'comment' => 'Группа для массовой инвалидации: tmdb, rawg, manual…',
            ),
            'data' => 
            array (
                'dbtype' => 'mediumtext',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Сериализованные данные (JSON)',
            ),
            'expires_at' => 
            array (
                'dbtype' => 'int',
                'precision' => '20',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'Unix-timestamp истечения кеша (0 = бессрочно)',
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
        ),
        'indexes' => 
        array (
            'unique_cache_key' => 
            array (
                'alias' => 'unique_cache_key',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'cache_key' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'cache_group_idx' => 
            array (
                'alias' => 'cache_group_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'cache_group' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'expires_at_idx' => 
            array (
                'alias' => 'expires_at_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'expires_at' => 
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
