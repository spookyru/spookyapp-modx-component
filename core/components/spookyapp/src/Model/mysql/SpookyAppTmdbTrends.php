<?php
namespace SpookyApp\Model\mysql;

use xPDO\xPDO;

class SpookyAppTmdbTrends extends \SpookyApp\Model\SpookyAppTmdbTrends
{

    public static $metaMap = array (
        'package' => 'SpookyApp\\Model',
        'version' => '3.0',
        'table' => 'spookyapp_tmdb_trends',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'id' => '',
            'original_title' => '',
            'title' => '',
            'overview' => '',
            'poster_path' => '',
            'backdrop_path' => '',
            'media_type' => '',
            'adult' => '',
            'original_language' => '',
            'genre' => '',
            'popularity' => '',
            'premier_date' => '',
            'vote_average' => '',
            'vote_count' => '',
            'origin_country' => '',
            'visible' => '',
            'black' => '',
            'actual' => '',
        ),
        'fieldMeta' => 
        array (
            'id' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'original_title' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'title' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'overview' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'poster_path' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'backdrop_path' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'media_type' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'adult' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'original_language' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'genre' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'popularity' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'premier_date' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'vote_average' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'vote_count' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'origin_country' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'visible' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'black' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'actual' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
        ),
        'indexes' => 
        array (
            'id' => 
            array (
                'alias' => 'id',
                'primary' => true,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'id' => 
                    array (
                        'length' => '120',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
    );

}
