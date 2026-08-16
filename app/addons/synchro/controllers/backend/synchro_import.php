<?php

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\ServiceProvider;

defined('BOOTSTRAP') or die('Access denied');

$command_bus = ServiceProvider::getCommandBus();

if ($mode === 'products') {
    $command_bus->dispatch(ImportDataCommand::create(ImportDataCommand::ENTITY_PRODUCTS));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'categories') {
    $command_bus->dispatch(ImportDataCommand::create(ImportDataCommand::ENTITY_CATEGORIES));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'manufacturers') {
    $command_bus->dispatch(ImportDataCommand::create(ImportDataCommand::ENTITY_MANUFACTURERS));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'features') {
    $command_bus->dispatch(ImportDataCommand::create(ImportDataCommand::ENTITY_FEATURES));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'feature_variants') {
    $command_bus->dispatch(ImportDataCommand::create(ImportDataCommand::ENTITY_FEATURE_VARIANTS));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'warehouses') {
    $command_bus->dispatch(ImportDataCommand::create(ImportDataCommand::ENTITY_WAREHOUSES));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

return [CONTROLLER_STATUS_NO_PAGE];
