<?php

use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

$cron_manager = ServiceProvider::getCronManager();
$import_process_manager = ServiceProvider::getImportProcessManager();
$request_script_id = isset($_REQUEST['script_id']) && is_scalar($_REQUEST['script_id'])
    ? (int) $_REQUEST['script_id']
    : 0;

/** @psalm-suppress PossiblyUndefinedArrayOffset */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'update') {
        $script_data = isset($_REQUEST['script_data']) && is_array($_REQUEST['script_data'])
            ? $_REQUEST['script_data']
            : [];
        /** @var array<string, array<array-key, string>|int|string|null> $script_data */
        if (!$cron_manager->updateScriptData($script_data, $request_script_id)) {
            fn_set_notification('E', __('error'), __('synchro.script_already_exists'));
        }
    }

    if ($mode === 'm_delete' && isset($_REQUEST['script_ids']) && is_array($_REQUEST['script_ids'])) {
        foreach ($_REQUEST['script_ids'] as $script_id) {
            if (is_scalar($script_id)) {
                $cron_manager->deleteCronScript((int) $script_id);
            }
        }
    }

    return [CONTROLLER_STATUS_OK, 'cron_script_manager.manage'];
}

$view = Tygh::$app['view'];
if ($mode === 'manage') {
    /** @var array<string, array<int|string, int|string>|bool|int|string> $request */
    $request = $_REQUEST;
    list($scripts, $search) = $cron_manager->getCronScripts(
        $request,
        Registry::get('settings.Appearance.admin_elements_per_page')
    );
    $view->assign('scripts', $scripts);
    $view->assign('search', $search);
    $view->assign(
        'synchro_import_processes',
        $import_process_manager->getLatestProcesses(array_map('intval', array_keys($scripts)))
    );
} elseif ($mode === 'update') {
    if ($request_script_id) {
        $view->assign('script_data', $cron_manager->getCronScriptData($request_script_id));
    }
} elseif ($mode === 'delete') {
    $cron_manager->deleteCronScript($request_script_id);

    return [CONTROLLER_STATUS_REDIRECT, 'cron_script_manager.manage'];
} elseif ($mode === 'launch') {
    if ($request_script_id) {
        $script = $cron_manager->getCronScriptData($request_script_id);
        if ($script) {
            if ($cron_manager->launchCronScriptInBackground($script, true)) {
                fn_set_notification('N', __('notice'), __('synchro.task_has_been_launched'));
            } else {
                fn_set_notification('W', __('warning'), __('synchro.script_is_already_running'));
            }
        }
    }

    return [CONTROLLER_STATUS_REDIRECT, 'cron_script_manager.manage'];
} elseif ($mode === 'interrupt') {
    $script_id = $request_script_id;
    $import_interruption_requested = $script_id
        ? $import_process_manager->requestParentInterruption($script_id)
        : false;
    $task_interruption_requested = $script_id
        ? $cron_manager->requestInterruption($script_id)
        : false;
    if ($import_interruption_requested || $task_interruption_requested) {
        fn_set_notification('N', __('notice'), __('synchro.task_interruption_requested'));
    } else {
        fn_set_notification('W', __('warning'), __('synchro.task_cannot_be_interrupted'));
    }

    return [CONTROLLER_STATUS_REDIRECT, 'cron_script_manager.manage'];
} elseif ($mode === 'interrupt_process') {
    $import_id = isset($_REQUEST['import_id']) && is_scalar($_REQUEST['import_id'])
        ? (int) $_REQUEST['import_id']
        : 0;
    if ($import_id && $import_process_manager->requestProcessInterruption($import_id)) {
        fn_set_notification('N', __('notice'), __('synchro.task_interruption_requested'));
    } else {
        fn_set_notification('W', __('warning'), __('synchro.task_cannot_be_interrupted'));
    }

    return [CONTROLLER_STATUS_REDIRECT, 'cron_script_manager.manage'];
} elseif ($mode === 'retry_process') {
    $import_id = isset($_REQUEST['import_id']) && is_scalar($_REQUEST['import_id'])
        ? (int) $_REQUEST['import_id']
        : 0;
    if ($import_id && $import_process_manager->retryProcess($import_id)) {
        fn_set_notification('N', __('notice'), __('synchro.import_process_retried'));
    } else {
        fn_set_notification('W', __('warning'), __('synchro.import_process_cannot_be_retried'));
    }

    return [CONTROLLER_STATUS_REDIRECT, 'cron_script_manager.manage'];
} elseif ($mode === 'retry_import') {
    $script_id = $request_script_id;
    if ($script_id && $import_process_manager->retryFailedProcesses($script_id)) {
        fn_set_notification('N', __('notice'), __('synchro.import_processes_retried'));
    } else {
        fn_set_notification('W', __('warning'), __('synchro.import_process_cannot_be_retried'));
    }

    return [CONTROLLER_STATUS_REDIRECT, 'cron_script_manager.manage'];
} elseif ($mode === 'run') {
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

    $script_id = $request_script_id;
    $script = $script_id
        ? $cron_manager->getCronScriptData($script_id)
        : [];
    if ($script) {
        $cron_manager->launchCronScript($script);
    }

    exit;
} elseif ($mode === 'launcher') {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    if (
        !isset($_REQUEST['cron_password'])
        || !is_scalar($_REQUEST['cron_password'])
        || !$cron_manager->isValidPassword($_REQUEST['cron_password'])
    ) {
        die(__('access_denied'));
    }

    if ($cron_manager->isMaintenanceMode()) {
        exit;
    }

    $import_process_manager->recoverStaleProcesses();
    $import_process_manager->dispatchPending();

    list($once_scripts) = $cron_manager->getCronScripts([
        'status'       => 'A',
        'run_mode'     => 'once',
        'skip_view'    => true,
        'sort_order'   => 'asc',
        'sort_by'      => 'created',
    ]);
    list($periodic_scripts) = $cron_manager->getCronScripts([
        'status'              => 'A',
        'run_mode'            => 'periodic',
        'period_by_timestamp' => TIME,
        'skip_view'           => true,
        'sort_order'          => 'asc',
        'sort_by'             => 'last_launch',
    ]);
    $scripts = $once_scripts + $periodic_scripts;

    foreach ($scripts as $script) {
        if ($cron_manager->isMaintenanceMode()) {
            exit;
        }

        if (!isset($script['script_id']) || !is_scalar($script['script_id'])) {
            continue;
        }
        $script = $cron_manager->getCronScriptData((int) $script['script_id']);
        if (!$script) {
            continue;
        }
        if ($script['inner_status'] === 'completed') {
            continue;
        }

        if (
            $script['run_mode'] === 'periodic'
            && !$cron_manager->checkCronRefreshTime($script, TIME)
        ) {
            continue;
        }

        $cron_manager->launchCronScriptInBackground($script);
    }

    exit;
}
