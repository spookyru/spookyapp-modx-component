<?php
namespace SpookyApp\Model\mysql;

use xPDO\xPDO;

class SpookyAppFootballMatchStats extends \SpookyApp\Model\SpookyAppFootballMatchStats
{

    public static $metaMap = array (
        'package' => 'SpookyApp\\Model',
        'version' => '3.0',
        'table' => 'spookyapp_football_matchstats',
        'extends' => 'xPDO\\Om\\xPDOObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'fixture_id' => '',
            'team_id' => '',
            'Shots_on_Goal' => '',
            'Shots_off_Goal' => '',
            'Shots_insidebox' => '',
            'Shots_outsidebox' => '',
            'Total_Shots' => '',
            'Blocked_Shots' => '',
            'Fouls' => '',
            'Corner_Kicks' => '',
            'Offsides' => '',
            'Ball_Possession' => '',
            'Yellow_Cards' => '',
            'Red_Cards' => '',
            'Goalkeeper_Saves' => '',
            'Total_passes' => '',
            'Passes_accurate' => '',
            'Passes_procent' => '',
        ),
        'fieldMeta' => 
        array (
            'fixture_id' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'team_id' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Shots_on_Goal' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Shots_off_Goal' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Shots_insidebox' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Shots_outsidebox' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Total_Shots' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Blocked_Shots' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Fouls' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Corner_Kicks' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Offsides' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Ball_Possession' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Yellow_Cards' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Red_Cards' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Goalkeeper_Saves' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Total_passes' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Passes_accurate' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'Passes_procent' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
        ),
        'indexes' => 
        array (
            'fixture_id' => 
            array (
                'alias' => 'fixture_id',
                'primary' => true,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'fixture_id' => 
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
