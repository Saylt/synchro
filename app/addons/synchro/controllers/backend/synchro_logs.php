<?php

use Tygh\Addons\Synchro\ServiceProvider;
use Tygh\Registry;
use Tygh\Tygh;

defined('BOOTSTRAP') or die('Access denied');

if ($mode === 'manage') {
    $params = [];
    foreach (['page', 'period', 'time_from', 'time_to', 'level', 'sort_by', 'sort_order', 'items_per_page'] as $name) {
        if (isset($_REQUEST[$name]) && is_scalar($_REQUEST[$name])) {
            $params[$name] = (string) $_REQUEST[$name];
        }
    }
    if (isset($_REQUEST['source'])) {
        $params['source'] = is_array($_REQUEST['source']) ? $_REQUEST['source'] : [$_REQUEST['source']];
    }

    $logging = ServiceProvider::getLogging();
    $items_per_page = (isset($params['items_per_page']) && !empty($params['items_per_page']))
        ? (int) $params['items_per_page']
        : (int) Registry::get('settings.Appearance.admin_elements_per_page'
    );
    list($logs, $search) = $logging->getLogs(
        $params,
        $items_per_page
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
    Tygh::$app['view']->assign('log_sources', $logging->getSources());
}
