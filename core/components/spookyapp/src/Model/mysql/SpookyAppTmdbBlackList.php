<?php
namespace SpookyApp\Model\mysql;

use xPDO\xPDO;

class SpookyAppTmdbBlackList extends \SpookyApp\Model\SpookyAppTmdbBlackList
{

    public static $metaMap = array (
        'package' => 'SpookyApp\\Model',
        'version' => '3.0',
        'table' => 'spookyapp_tmdb_blacklist',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'id_tmdb' => '',
            'id_imdb' => '',
            'id_kp' => '',
        ),
        'fieldMeta' => 
        array (
            'id_tmdb' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'id_imdb' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'id_kp' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
        ),
        'indexes' => 
        array (
            'id_tmdb' => 
            array (
                'alias' => 'id_tmdb',
                'primary' => true,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'id_tmdb' => 
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
