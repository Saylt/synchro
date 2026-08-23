<?php

defined('BOOTSTRAP') or die('Access denied');

/** @var array<string, array> $schema */

$schema['synchro'] = [
    'name'            => __('synchro.sync_data_name'),
    'update_template' => 'addons/synchro/views/sync_data/components/update.tpl',
];

return $schema;
