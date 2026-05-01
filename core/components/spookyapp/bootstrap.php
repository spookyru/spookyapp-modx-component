<?php

/**
 * @var \MODX\Revolution\modX $modx
 * @var array $namespace
 */

// Load the classes
$modx->addPackage('SpookyApp\Model', $namespace['path'] . 'src/', null, 'SpookyApp\\');

$modx->services->add('SpookyApp', function ($c) use ($modx) {
    return new SpookyApp\SpookyApp($modx);
});
