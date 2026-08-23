<?php

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\ServiceProvider;

defined('BOOTSTRAP') or die('Access denied');

/**
 * @var array<string, array{middleware: array<callable>, handler: callable}> $schema Command handlers schema
 */
$schema = [
    ImportDataCommand::class => [
        'middleware' => [],
        'handler'    => static function (ImportDataCommand $command) {
            return ServiceProvider::getImportDataCommandHandler()->handle($command);
        },
    ],
];

return $schema;
