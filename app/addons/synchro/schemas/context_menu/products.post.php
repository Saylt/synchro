<?php

use Tygh\ContextMenu\Items\GroupItem;

defined('BOOTSTRAP') or die('Access denied!');

/** @var array $schema */
$schema['items']['synchro_synchronization'] = [
    'name'     => ['template' => 'synchro.synchronization'],
    'type'     => GroupItem::class,
    'items'    => [
        'full'      => [
            'name'     => ['template' => 'synchro.synchronize_product_full'],
            'dispatch' => 'products.synchro_full',
            'position' => 10,
        ],
        'actualize' => [
            'name'     => ['template' => 'synchro.synchronize_product_actualize'],
            'dispatch' => 'products.synchro_actualize',
            'position' => 20,
        ],
    ],
    'position' => 80,
];

return $schema;
