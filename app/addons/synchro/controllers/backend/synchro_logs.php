<?php

use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Registry;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

if ($mode === 'manage') {
    $params = [];
    foreach (['page', 'period', 'time_from', 'time_to', 'level', 'source', 'sort_by', 'sort_order'] as $name) {
        if (isset($_REQUEST[$name]) && is_scalar($_REQUEST[$name])) {
            $params[$name] = (string) $_REQUEST[$name];
        }
    }

    $logging = ServiceProvider::getLogging();
    list($logs, $search) = $logging->getLogs(
        $params,
        (int) Registry::get('settings.Appearance.admin_elements_per_page')
    );
    foreach ($logs as &$log) {
        $context = isset($log['context']) ? json_decode((string) $log['context'], true) : null;
        if (is_array($context)) {
            $log['context'] = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
    unset($log);

    Tygh::$app['view']->assign('logs', $logs);
    Tygh::$app['view']->assign('search', $search);
    Tygh::$app['view']->assign('log_levels', $logging->getLevels());
}
