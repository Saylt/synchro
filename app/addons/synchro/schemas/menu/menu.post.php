<?php

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */
$schema['top']['administration']['items']['cron_script_manager'] = [
    'href'     => 'cron_script_manager.manage',
    'title'    => __('synchro.cron_script_manager'),
    'position' => 1000,
    'attrs'    => [
        'class' => 'is-addon',
    ],
];

return $schema;
