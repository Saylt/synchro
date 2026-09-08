<?php

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Application\ProductApplicationManager;
use Tygh\Addons\Synchro\Enum\Logging;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Http;
use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

$import_repository = ServiceProvider::getImportEntityRepository();
$cron_manager = ServiceProvider::getCronManager();
$import_process_manager = ServiceProvider::getImportProcessManager();
$cron_script_id = isset($_REQUEST['cron_script_id']) && is_scalar($_REQUEST['cron_script_id'])
    ? (int) $_REQUEST['cron_script_id']
    : 0;
$company_id = fn_get_runtime_company_id();
$source_import_id = isset($_REQUEST['import_id']) && is_scalar($_REQUEST['import_id'])
    ? (int) $_REQUEST['import_id']
    : 0;
$log_exception = static function (Throwable $exception) use ($mode) {
    fn_log_event(Logging::LOG_TYPE_CRON_MANAGER, Logging::ACTION_ERRORS, [
        'script' => 'synchro_import.' . $mode,
        'error'  => $exception->getMessage(),
    ]);
};

if ($mode === 'apply_categories') {
    if (!$source_import_id) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    try {
        ServiceProvider::getCategoryApplicationManager()->apply($source_import_id, $cron_script_id);
    } catch (TaskInterruptedException $exception) {
        $log_exception($exception);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'apply_products') {
    if (!$source_import_id) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    try {
        ServiceProvider::getProductApplicationManager()->apply(
            $source_import_id,
            ProductApplicationManager::MODE_FULL,
            $cron_script_id
        );
    } catch (TaskInterruptedException $exception) {
        $log_exception($exception);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'apply_test_products') {
    if (!$source_import_id) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    try {
        ServiceProvider::getProductApplicationManager()->apply(
            $source_import_id,
            ProductApplicationManager::MODE_TEST,
            $cron_script_id
        );
    } catch (TaskInterruptedException $exception) {
        $log_exception($exception);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'actualize_products') {
    if (!$source_import_id) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    try {
        ServiceProvider::getProductApplicationManager()->apply(
            $source_import_id,
            ProductApplicationManager::MODE_ACTUALIZE,
            $cron_script_id
        );
    } catch (TaskInterruptedException $exception) {
        $log_exception($exception);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

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
    $script = $cron_script_id ? $cron_manager->getCronScriptData($cron_script_id) : [];
    if (!$script) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    /** @var array<string, array<int, string>|int|null|string> $script */

    try {
        if ($script['is_test_import'] === 'Y') {
            $total_items = 10;
        } else {
            $metadata = $get_api_data(ImportDataCommand::ENTITY_PRODUCTS, [
                'page'  => 1,
                'limit' => 1,
            ]);
            $total_items = $metadata['pgn']['total'];
        }

        $parent_import_id = $import_process_manager->createProductImport($script, $company_id, $total_items);
        if (!$cron_manager->markTaskWaitingForChildren($cron_script_id)) {
            $import_process_manager->requestParentInterruption($cron_script_id);

            throw new TaskInterruptedException('Import task interruption requested');
        }
        $import_process_manager->dispatchPending($parent_import_id);
        $import_process_manager->reconcileParent($parent_import_id);
    } catch (TaskInterruptedException $exception) {
        $log_exception($exception);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'product_process') {
    @set_time_limit(0);
    @ini_set('memory_limit', '256M');

    if (
        !defined('CONSOLE')
        || !isset($_REQUEST['cron_password'])
        || !is_scalar($_REQUEST['cron_password'])
        || !$cron_manager->isValidPassword($_REQUEST['cron_password'])
    ) {
        die(__('access_denied'));
    }

    $import_id = isset($_REQUEST['import_id']) && is_scalar($_REQUEST['import_id'])
        ? (int) $_REQUEST['import_id']
        : 0;
    $process = $import_process_manager->getProcess($import_id);
    if (
        !$process
        || !(int) $process['parent_import_id']
        || $process['entity_type'] !== ImportDataCommand::ENTITY_PRODUCTS
        || $process['status'] !== $import_repository::STATUS_PROCESSING
    ) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    Registry::set('runtime.company_id', (int) $process['company_id']);
    $command_bus = ServiceProvider::getCommandBus();

    try {
        for ($page = (int) $process['page_from']; $page <= (int) $process['page_to']; $page++) {
            $import_process_manager->ensureProcessCanContinue($import_id);
            $import_process_manager->updateProgress($import_id, $page);
            $data = $get_api_data(ImportDataCommand::ENTITY_PRODUCTS, [
                'page'  => $page,
                'limit' => (int) $process['page_limit'],
            ]);
            $cron_manager->updateProgressStatus(
                (int) $process['cron_script_id'],
                __('synchro.importing_products', [
                    '[page]'  => $page,
                    '[pages]' => $process['page_to'],
                ])
            );
            $command_bus->dispatch(
                ImportDataCommand::create(
                    ImportDataCommand::ENTITY_PRODUCTS,
                    $data,
                    $import_id,
                    (int) $process['cron_script_id'],
                    $import_id
                )
            );
        }

        $import_process_manager->ensureProcessCanContinue($import_id);
        $import_process_manager->completeProcess($import_id);
    } catch (TaskInterruptedException $exception) {
        $import_process_manager->cancelProcess($import_id);
        $log_exception($exception);

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $import_process_manager->failProcess($import_id, $exception->getMessage());
        $log_exception($exception);

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'categories') {
    if ($cron_script_id) {
        $cron_manager->updateProgressStatus($cron_script_id, __('synchro.importing_categories'));
    }
    $import_id = $import_repository->startImport($company_id, ImportDataCommand::ENTITY_CATEGORIES);

    try {
        $data = $get_api_data(ImportDataCommand::ENTITY_CATEGORIES);
        $command_bus = ServiceProvider::getCommandBus();
        $command_bus->dispatch(
            ImportDataCommand::create(
                ImportDataCommand::ENTITY_CATEGORIES,
                $data,
                $import_id,
                $cron_script_id
            )
        );
        $import_repository->completeImport($import_id);
        if ($cron_script_id) {
            $cron_manager->queuePostProcess(
                $cron_script_id,
                $import_id,
                ImportEntityRepository::SOURCE_TYPE_FULL,
                ImportEntityRepository::STATUS_COMPLETED
            );
        }
    } catch (TaskInterruptedException $exception) {
        $import_repository->failImport($import_id);
        $log_exception($exception);

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $import_repository->failImport($import_id);
        $log_exception($exception);

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'manufacturers') {
    if ($cron_script_id) {
        $cron_manager->updateProgressStatus($cron_script_id, __('synchro.importing_manufacturers'));
    }
    $import_id = $import_repository->startImport($company_id, ImportDataCommand::ENTITY_MANUFACTURERS);

    try {
        $data = $get_api_data(ImportDataCommand::ENTITY_MANUFACTURERS);
        $command_bus = ServiceProvider::getCommandBus();
        $command_bus->dispatch(
            ImportDataCommand::create(
                ImportDataCommand::ENTITY_MANUFACTURERS,
                $data,
                $import_id,
                $cron_script_id
            )
        );
        $import_repository->completeImport($import_id);
    } catch (TaskInterruptedException $exception) {
        $import_repository->failImport($import_id);
        $log_exception($exception);

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $import_repository->failImport($import_id);
        $log_exception($exception);

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'features') {
    if ($cron_script_id) {
        $cron_manager->updateProgressStatus($cron_script_id, __('synchro.importing_features'));
    }
    ServiceProvider::getCommandBus()->dispatch(
        ImportDataCommand::create(ImportDataCommand::ENTITY_FEATURES, [], 0, $cron_script_id)
    );

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'feature_variants') {
    if ($cron_script_id) {
        $cron_manager->updateProgressStatus($cron_script_id, __('synchro.importing_feature_variants'));
    }
    ServiceProvider::getCommandBus()->dispatch(
        ImportDataCommand::create(ImportDataCommand::ENTITY_FEATURE_VARIANTS, [], 0, $cron_script_id)
    );

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'warehouses') {
    if ($cron_script_id) {
        $cron_manager->updateProgressStatus($cron_script_id, __('synchro.importing_warehouses'));
    }
    ServiceProvider::getCommandBus()->dispatch(
        ImportDataCommand::create(ImportDataCommand::ENTITY_WAREHOUSES, [], 0, $cron_script_id)
    );

    return [CONTROLLER_STATUS_NO_CONTENT];
}

return [CONTROLLER_STATUS_NO_PAGE];
