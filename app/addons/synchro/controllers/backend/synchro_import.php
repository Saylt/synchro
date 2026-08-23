<?php

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Http;

defined('BOOTSTRAP') or die('Access denied');

$command_bus = ServiceProvider::getCommandBus();
$import_repository = ServiceProvider::getImportEntityRepository();
$company_id = fn_get_runtime_company_id();
$api_url = 'https://svetelektro.net/index.php?option=com_vmtools&task=exportall.make'
    . '&centerkey=54ffc087d87dae187499273060174614';

$get_api_data = static function (string $action, array $request_data = []) use ($api_url): array {
    $is_http_logging_enabled = Http::$logging;
    Http::$logging = false;

    try {
        $response = Http::get(
            $api_url,
            array_merge(['action' => $action], $request_data)
        );
    } finally {
        Http::$logging = $is_http_logging_enabled;
    }

    if (!is_string($response) || Http::getStatus() !== Http::STATUS_OK) {
        throw new RuntimeException(__('synchro.api_request_failed', [
            '[action]' => $action,
            '[error]'  => Http::getError() ?: __('error_occurred'),
        ]));
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException(__('synchro.api_invalid_response', [
            '[action]' => $action,
        ]));
    }

    if (empty($data['ok'])) {
        throw new RuntimeException(__('synchro.api_request_failed', [
            '[action]' => $action,
            '[error]'  => $data['error'],
        ]));
    }

    /**
     * @var array{
     *     ok: int,
     *     error: string,
     *     pgn: array{total: int, pages: int, limit: int, page: int},
     *     data: array<array-key, array>
     * } $data
     */
    return $data;
};

if ($mode === 'products') {
    $page = 1;
    $import_id = $import_repository->startImport($company_id, ImportDataCommand::ENTITY_PRODUCTS);

    try {
        do {
            $data = $get_api_data(ImportDataCommand::ENTITY_PRODUCTS, ['page' => $page]);
            $command_bus->dispatch(
                ImportDataCommand::create(ImportDataCommand::ENTITY_PRODUCTS, $data, $import_id)
            );
            $page++;
        } while ($page <= $data['pgn']['pages']);

        $import_repository->completeImport($import_id);
    } catch (Throwable $exception) {
        $import_repository->failImport($import_id);

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'categories') {
    $import_id = $import_repository->startImport($company_id, ImportDataCommand::ENTITY_CATEGORIES);

    try {
        $data = $get_api_data(ImportDataCommand::ENTITY_CATEGORIES);
        $command_bus->dispatch(
            ImportDataCommand::create(ImportDataCommand::ENTITY_CATEGORIES, $data, $import_id)
        );
        $import_repository->completeImport($import_id);
    } catch (Throwable $exception) {
        $import_repository->failImport($import_id);

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'manufacturers') {
    $import_id = $import_repository->startImport($company_id, ImportDataCommand::ENTITY_MANUFACTURERS);

    try {
        $data = $get_api_data(ImportDataCommand::ENTITY_MANUFACTURERS);
        $command_bus->dispatch(
            ImportDataCommand::create(ImportDataCommand::ENTITY_MANUFACTURERS, $data, $import_id)
        );
        $import_repository->completeImport($import_id);
    } catch (Throwable $exception) {
        $import_repository->failImport($import_id);

        throw $exception;
    }

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
