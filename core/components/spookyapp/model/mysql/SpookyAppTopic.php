<?php
namespace spookyapp\mysql;

use xPDO\xPDO;

class SpookyAppTopic extends \spookyapp\SpookyAppTopic
{

    public static $metaMap = array (
        'package' => 'spookyapp',
        'version' => '3.0',
        'table' => 'spookyapp_topics',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'source' => '',
            'title' => '',
            'url' => '',
            'description' => '',
            'category' => '',
            'published_at' => '',
            'score' => 0.0,
            'metadata' => '',
            'cached_at' => 0,
        ),
        'fieldMeta' => 
        array (
            'source' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '50',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'comment' => 'Источник: habr, reddit, tmdb, rawg…',
            ),
            'title' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '255',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'comment' => 'Заголовок топика',
            ),
            'url' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '500',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'URL оригинального материала',
            ),
            'description' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Краткое описание / аннотация',
            ),
            'category' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Категория внутри источника',
            ),
            'published_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
                'default' => '',
                'comment' => 'Дата публикации в источнике',
            ),
            'score' => 
            array (
                'dbtype' => 'decimal',
                'precision' => '10,2',
                'phptype' => 'float',
                'null' => false,
                'default' => 0.0,
                'comment' => 'Рейтинг / релевантность (от 0.00 до 99999.99)',
            ),
            'metadata' => 
            array (
                'dbtype' => 'mediumtext',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Дополнительные данные в формате JSON',
            ),
            'cached_at' => 
            array (
                'dbtype' => 'int',
                'precision' => '20',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'Unix-timestamp момента кеширования',
            ),
        ),
        'indexes' => 
        array (
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
            'category_idx' => 
            array (
                'alias' => 'category_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'category' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'score_idx' => 
            array (
                'alias' => 'score_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'score' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'published_at_idx' => 
            array (
                'alias' => 'published_at_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'published_at' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => true,
                    ),
                ),
            ),
        ),
    );

}
