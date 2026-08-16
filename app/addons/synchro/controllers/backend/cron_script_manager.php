<?php

use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

$cron_manager = ServiceProvider::getCronManager();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($mode === 'update') {
        if (!$cron_manager->updateScriptData($_REQUEST['script_data'], $_REQUEST['script_id'])) {
            fn_set_notification('E', __('error'), __('synchro.script_already_exists'));
        }
    }

    if ($mode === 'm_delete' && !empty($_REQUEST['script_ids'])) {
        foreach ($_REQUEST['script_ids'] as $script_id) {
            $cron_manager->deleteCronScript($script_id);
        }
    }

    return [CONTROLLER_STATUS_OK, 'cron_script_manager.manage'];
}

$view = Tygh::$app['view'];
if ($mode === 'manage') {
    list($scripts, $search) = $cron_manager->getCronScripts(
        $_REQUEST,
        Registry::get('settings.Appearance.admin_elements_per_page')
    );
    $view->assign('scripts', $scripts);
    $view->assign('search', $search);
} elseif ($mode === 'update') {
    if (!empty($_REQUEST['script_id'])) {
        $view->assign('script_data', $cron_manager->getCronScriptData($_REQUEST['script_id']));
    }
} elseif ($mode === 'delete') {
    $cron_manager->deleteCronScript($_REQUEST['script_id']);

    return [CONTROLLER_STATUS_REDIRECT, 'cron_script_manager.manage'];
} elseif ($mode === 'launch') {
    if (!empty($_REQUEST['script_id'])) {
        $script = $cron_manager->getCronScriptData($_REQUEST['script_id']);
        if ($script) {
            if ($cron_manager->launchCronScript($script)) {
                fn_set_notification('N', __('notice'), __('synchro.task_has_been_executed'));
            } else {
                fn_set_notification('W', __('warning'), __('synchro.script_is_already_running'));
            }
        }
    }

    return [CONTROLLER_STATUS_REDIRECT, 'cron_script_manager.manage'];
} elseif ($mode === 'launcher') {
    @set_time_limit(0);
    @ini_set('memory_limit', '256M');

    if (
        !isset($_REQUEST['cron_password'])
        || !$cron_manager->isValidPassword((string) $_REQUEST['cron_password'])
    ) {
        die(__('access_denied'));
    }

    list($scripts) = $cron_manager->getCronScripts([
        'status'              => 'A',
        'period_by_timestamp' => TIME,
        'skip_view'           => true,
        'sort_order'          => 'asc',
        'sort_by'             => 'last_launch',
    ]);

    foreach ($scripts as $script) {
        if ($cron_manager->isMaintenanceMode()) {
            exit;
        }

        $last_launch_time = $script['last_launch'];

        // Cron is launched every minute without waiting for the previous iteration to finish.
        // Reloading script data minimizes collisions between concurrent launcher processes.
        $script = $cron_manager->getCronScriptData($script['script_id']);
        if (!$script || $last_launch_time < $script['last_launch']) {
            continue;
        }

        if (!$cron_manager->checkCronRefreshTime($script, TIME)) {
            continue;
        }

        if (!$cron_manager->isCronScriptRunning($script)) {
            $cron_manager->launchCronScript($script);
        }
    }

    exit;
}
