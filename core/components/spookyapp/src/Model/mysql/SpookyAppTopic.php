<?php
namespace SpookyApp\Model\mysql;

use xPDO\xPDO;

class SpookyAppTopic extends \SpookyApp\Model\SpookyAppTopic
{

    public static $metaMap = array (
        'package' => 'SpookyApp\\Model',
        'version' => '3.0',
        'table' => 'spookyapp_topics',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'topic_id' => '',
            'source' => '',
            'title' => '',
            'url' => '',
            'description' => '',
            'category' => '',
            'published_at' => null,
            'score' => 0.0,
            'metadata' => '',
            'status' => 0,
            'assigned_to' => 0,
            'notes' => '',
            'cached_at' => 0,
            'created_at' => null,
            'updated_at' => null,
        ),
        'fieldMeta' => 
        array (
            'topic_id' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '64',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'index' => 'unique',
                'comment' => 'Уникальный идентификатор темы (source_type_id)',
            ),
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
                'default' => null,
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
            'status' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'Статус: 0=Новая, 1=Одобрена, 2=В работе, 3=Опубликована, 4=Отклонена, 5=Архив',
            ),
            'assigned_to' => 
            array (
                'dbtype' => 'int',
                'precision' => '11',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'ID пользователя MODX, которому назначена тема',
            ),
            'notes' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Заметки редактора',
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
            'created_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
                'default' => null,
                'comment' => 'Дата создания записи',
            ),
            'updated_at' => 
            array (
                'dbtype' => 'datetime',
                'phptype' => 'datetime',
                'null' => true,
                'default' => null,
                'comment' => 'Дата последнего обновления',
            ),
        ),
        'indexes' => 
        array (
            'topic_id_unique' => 
            array (
                'alias' => 'topic_id_unique',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'topic_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => true,
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
            'status_idx' => 
            array (
                'alias' => 'status_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'status' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'created_at_idx' => 
            array (
                'alias' => 'created_at_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'created_at' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => true,
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
