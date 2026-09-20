<?php

use Tygh\Addons\Synchro\Commands\ImportDataCommand;
use Tygh\Addons\Synchro\Application\ProductApplicationManager;
use Tygh\Addons\Synchro\Exceptions\TaskInterruptedException;
use Tygh\Addons\Synchro\Repository\ImportEntityRepository;
use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

$import_repository = ServiceProvider::getImportEntityRepository();
$cron_manager = ServiceProvider::getCronManager();
$import_process_manager = ServiceProvider::getImportProcessManager();
$logging = ServiceProvider::getLogging();
$cron_script_id = isset($_REQUEST['cron_script_id']) && is_scalar($_REQUEST['cron_script_id'])
    ? (int) $_REQUEST['cron_script_id']
    : 0;
$company_id = fn_get_runtime_company_id();
$source_import_id = isset($_REQUEST['import_id']) && is_scalar($_REQUEST['import_id'])
    ? (int) $_REQUEST['import_id']
    : 0;
$log_source = 'synchro_import.' . $mode;
if ($cron_script_id) {
    register_shutdown_function(static function ($script_id) {
        ServiceProvider::getCronManager()->reportTaskPeakMemory($script_id);
    }, $cron_script_id);
}

if (
    $mode === 'apply_categories'
    || $mode === 'apply_products'
    || $mode === 'apply_test_products'
    || $mode === 'actualize_products'
) {
    $product_application_modes = [
        'apply_products'      => ProductApplicationManager::MODE_FULL,
        'apply_test_products' => ProductApplicationManager::MODE_TEST,
        'actualize_products'  => ProductApplicationManager::MODE_ACTUALIZE,
    ];
    $script = $cron_script_id ? $cron_manager->getCronScriptData($cron_script_id) : [];
    if (!$source_import_id || !$script) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    try {
        $parent_import_id = $mode === 'apply_categories'
            ? $import_process_manager->createCategoryApplication($script, $source_import_id)
            : $import_process_manager->createProductApplication(
                $script,
                $source_import_id,
                $product_application_modes[$mode]
            );
        if (!$cron_manager->markTaskWaitingForChildren($cron_script_id)) {
            $import_process_manager->requestParentInterruption($cron_script_id);

            throw new TaskInterruptedException(__('synchro.exception.application_interruption_requested'));
        }
        $import_process_manager->dispatchPending($parent_import_id);
        $import_process_manager->reconcileParent($parent_import_id);
    } catch (TaskInterruptedException $exception) {
        $logging->error($log_source, $exception->getMessage());

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'apply_manufacturers') {
    if (!$source_import_id || !$cron_script_id) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    try {
        ServiceProvider::getManufacturerApplicationManager()->apply($source_import_id);
    } catch (TaskInterruptedException $exception) {
        $logging->error($log_source, $exception->getMessage());

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $logging->error($log_source, $exception->getMessage());

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'archive_products') {
    $script = $cron_script_id ? $cron_manager->getCronScriptData($cron_script_id) : [];
    $import = $source_import_id ? $import_repository->findImport($source_import_id) : [];
    if (
        !$script
        || (int) $script['runtime_import_id'] !== $source_import_id
        || !$import
        || $import['entity_type'] !== ImportDataCommand::ENTITY_PRODUCTS
        || $import['source_type'] !== ImportEntityRepository::SOURCE_TYPE_FULL
        || $import['status'] !== ImportEntityRepository::STATUS_COMPLETED
    ) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    Registry::set('runtime.company_id', $import['company_id']);
    try {
        ServiceProvider::getProductArchiver()->archive($import['company_id'], $cron_script_id);
    } catch (TaskInterruptedException $exception) {
        $logging->error($log_source, $exception->getMessage());

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $logging->error($log_source, $exception->getMessage());

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

$product_worker_modes = [
    'synchro_import.apply_products'      => ProductApplicationManager::MODE_FULL,
    'synchro_import.apply_test_products' => ProductApplicationManager::MODE_TEST,
    'synchro_import.actualize_products'  => ProductApplicationManager::MODE_ACTUALIZE,
];
if ($mode === 'product_application_process' || $mode === 'category_application_process') {
    @ini_set('memory_limit', '256M');

    if (
        !defined('CONSOLE')
        || !isset($_REQUEST['cron_password'])
        || !is_scalar($_REQUEST['cron_password'])
        || !$cron_manager->isValidPassword($_REQUEST['cron_password'])
    ) {
        die(__('access_denied'));
    }

    $process = $import_process_manager->getProcess($source_import_id);
    $expected_entity_type = $mode === 'product_application_process'
        ? ImportDataCommand::ENTITY_PRODUCTS
        : ImportDataCommand::ENTITY_CATEGORIES;
    if (
        !$process
        || !$process['parent_import_id']
        || !$process['staging_import_id']
        || $process['entity_type'] !== $expected_entity_type
        || $process['status'] !== ImportEntityRepository::STATUS_PROCESSING
    ) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    $script = $cron_manager->getCronScriptData($process['cron_script_id']);
    if (
        !$script
        || !isset($script['script'])
        || (
            $mode === 'product_application_process'
            && !isset($product_worker_modes[$script['script']])
        )
        || (
            $mode === 'category_application_process'
            && $script['script'] !== 'synchro_import.apply_categories'
        )
    ) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    Registry::set('runtime.company_id', $process['company_id']);
    $process_metric_id = $cron_manager->startProcessMetric(
        (int) $process['cron_script_id'],
        $source_import_id
    );

    try {
        $import_process_manager->ensureProcessCanContinue($source_import_id);
        if ($mode === 'product_application_process') {
            ServiceProvider::getProductApplicationManager()->process(
                $process,
                $product_worker_modes[$script['script']]
            );
        } else {
            ServiceProvider::getCategoryApplicationManager()->process($process);
        }
        $import_process_manager->ensureProcessCanContinue($source_import_id);
        $cron_manager->finishProcessMetric($process_metric_id, ImportEntityRepository::STATUS_COMPLETED);
        $import_process_manager->completeProcess($source_import_id);
        if (
            $mode === 'product_application_process'
            && $script['script'] === 'synchro_import.apply_products'
        ) {
            ServiceProvider::getProductArchivingManager()->queueAfterApplication($process);
        }
    } catch (TaskInterruptedException $exception) {
        $cron_manager->finishProcessMetric($process_metric_id, ImportEntityRepository::STATUS_CANCELLED);
        $import_process_manager->cancelProcess($source_import_id);
        $logging->error($log_source, $exception->getMessage());

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $cron_manager->finishProcessMetric($process_metric_id, ImportEntityRepository::STATUS_FAILED);
        $import_process_manager->failProcess($source_import_id, $exception->getMessage());
        $logging->error($log_source, $exception->getMessage());

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

$api_client = ServiceProvider::getApiClient();

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
            $metadata = $api_client->getData(ImportDataCommand::ENTITY_PRODUCTS, [
                'page'  => 1,
                'limit' => 1,
            ]);
            $total_items = $metadata['pgn']['total'];
        }

        $parent_import_id = $import_process_manager->createProductImport($script, $company_id, $total_items);
        if (!$cron_manager->markTaskWaitingForChildren($cron_script_id)) {
            $import_process_manager->requestParentInterruption($cron_script_id);

            throw new TaskInterruptedException(__('synchro.exception.import_task_interruption_requested'));
        }
        $import_process_manager->dispatchPending($parent_import_id);
        $import_process_manager->reconcileParent($parent_import_id);
    } catch (TaskInterruptedException $exception) {
        $logging->error($log_source, $exception->getMessage());

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'product_process') {
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
        || !$process['parent_import_id']
        || $process['entity_type'] !== ImportDataCommand::ENTITY_PRODUCTS
        || $process['status'] !== $import_repository::STATUS_PROCESSING
    ) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    Registry::set('runtime.company_id', $process['company_id']);
    $command_bus = ServiceProvider::getCommandBus();
    $process_metric_id = $cron_manager->startProcessMetric(
        (int) $process['cron_script_id'],
        $import_id
    );

    try {
        for ($page = $process['page_from']; $page <= $process['page_to']; $page++) {
            $import_process_manager->ensureProcessCanContinue($import_id);
            $import_process_manager->updateProgress($import_id, $page);
            $data = $api_client->getData(ImportDataCommand::ENTITY_PRODUCTS, [
                'page'  => $page,
                'limit' => $process['page_limit'],
            ]);
            $cron_manager->updateProgressStatus(
                $process['cron_script_id'],
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
                    $process['cron_script_id'],
                    $import_id
                )
            );
        }

        $import_process_manager->ensureProcessCanContinue($import_id);
        $import_process_manager->completeProcess($import_id);
        $cron_manager->finishProcessMetric($process_metric_id, ImportEntityRepository::STATUS_COMPLETED);
    } catch (TaskInterruptedException $exception) {
        $cron_manager->finishProcessMetric($process_metric_id, ImportEntityRepository::STATUS_CANCELLED);
        $import_process_manager->cancelProcess($import_id);
        $logging->error($log_source, $exception->getMessage());

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $cron_manager->finishProcessMetric($process_metric_id, ImportEntityRepository::STATUS_FAILED);
        $import_process_manager->failProcess($import_id, $exception->getMessage());
        $logging->error($log_source, $exception->getMessage());

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
        $data = $api_client->getData(ImportDataCommand::ENTITY_CATEGORIES);
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
        $logging->error($log_source, $exception->getMessage());

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $import_repository->failImport($import_id);
        $logging->error($log_source, $exception->getMessage());

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
        $data = $api_client->getData(ImportDataCommand::ENTITY_MANUFACTURERS);
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
        $logging->error($log_source, $exception->getMessage());

        return [CONTROLLER_STATUS_NO_CONTENT];
    } catch (Throwable $exception) {
        $import_repository->failImport($import_id);
        $logging->error($log_source, $exception->getMessage());

        throw $exception;
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

return [CONTROLLER_STATUS_NO_PAGE];
