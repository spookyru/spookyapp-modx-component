<?php
namespace spookyapp\mysql;

use xPDO\xPDO;

class SpookyAppMedia extends \spookyapp\SpookyAppMedia
{

    public static $metaMap = array (
        'package' => 'spookyapp',
        'version' => '3.0',
        'table' => 'spookyapp_media',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'object_type' => '',
            'object_id' => 0,
            'media_type' => 'image',
            'media_role' => 'poster',
            'url' => '',
            'local_path' => '',
            'width' => 0,
            'height' => 0,
            'filesize' => 0,
            'alt' => '',
            'sort_order' => 0,
            'createdon' => 0,
        ),
        'fieldMeta' => 
        array (
            'object_type' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '50',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'comment' => 'Тип родительского объекта: chunk, topic…',
            ),
            'object_id' => 
            array (
                'dbtype' => 'int',
                'precision' => '11',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'ID родительского объекта',
            ),
            'media_type' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '50',
                'phptype' => 'string',
                'null' => false,
                'default' => 'image',
                'comment' => 'Тип медиа: image, video, audio, document',
            ),
            'media_role' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '50',
                'phptype' => 'string',
                'null' => false,
                'default' => 'poster',
                'comment' => 'Роль: poster, backdrop, screenshot, trailer, audio…',
            ),
            'url' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '1000',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'comment' => 'URL медиафайла (внешний или локальный путь)',
            ),
            'local_path' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '1000',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Локальный путь (если файл скачан)',
            ),
            'width' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'phptype' => 'integer',
                'null' => true,
                'default' => 0,
                'comment' => 'Ширина в пикселях',
            ),
            'height' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'phptype' => 'integer',
                'null' => true,
                'default' => 0,
                'comment' => 'Высота в пикселях',
            ),
            'filesize' => 
            array (
                'dbtype' => 'int',
                'precision' => '11',
                'phptype' => 'integer',
                'null' => true,
                'default' => 0,
                'comment' => 'Размер файла в байтах',
            ),
            'alt' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '255',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
                'comment' => 'Alt-текст для изображений',
            ),
            'sort_order' => 
            array (
                'dbtype' => 'smallint',
                'precision' => '5',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'Порядок сортировки',
            ),
            'createdon' => 
            array (
                'dbtype' => 'int',
                'precision' => '20',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'comment' => 'Unix-timestamp создания',
            ),
        ),
        'indexes' => 
        array (
            'object_idx' => 
            array (
                'alias' => 'object_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'object_type' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                    'object_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'media_role_idx' => 
            array (
                'alias' => 'media_role_idx',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'media_role' => 
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
