<?php
namespace SpookyApp\Model\mysql;

use xPDO\xPDO;

class SpookyAppTmdbReleases extends \SpookyApp\Model\SpookyAppTmdbReleases
{

    public static $metaMap = array (
        'package' => 'SpookyApp\\Model',
        'version' => '3.0',
        'table' => 'spookyapp_tmdb_releases',
        'extends' => 'xPDO\\Om\\xPDOObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'id' => '',
            'title' => '',
            'original_title' => '',
            'overview' => '',
            'poster_path' => '',
            'backdrop_path' => '',
            'release_date' => '',
            'genre_ids' => '',
            'popularity' => '',
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
            'title' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'original_title' => 
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
            'release_date' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'genre_ids' => 
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
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
    );

}
